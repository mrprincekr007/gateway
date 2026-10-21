<?php
declare(strict_types=1);

require __DIR__ . '/../config/config.php';
require_once APP_ROOT . '/includes/icons.php';
require_once APP_ROOT . '/includes/otp.php';

$user   = require_login();
$active = 'profile';

$error = null;
$saved = null;
$action = post('action');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verify_csrf(post('csrf_token'))) {
        $error = 'Session expired. Please try again.';
    } else {
        /* ---------- Personal details ---------- */
        if ($action === 'personal') {
            $name  = trim((string) post('name'));
            $upiId = trim((string) post('upi_id'));

            if (mb_strlen($name) < 2) {
                $error = 'Name must be at least 2 characters.';
            } elseif ($upiId !== '' && !str_contains($upiId, '@')) {
                $error = 'Enter a valid UPI ID (e.g. yourname@okhdfcbank) or leave it empty.';
            } else {
                db()->prepare('UPDATE users SET name = ?, upi_id = ? WHERE id = ?')
                    ->execute([$name, $upiId !== '' ? $upiId : null, $user['id']]);
                $saved = 'Personal details and settlement UPI ID updated successfully.';
            }
        }

        /* ---------- Business details ---------- */
        if ($action === 'business') {
            $phone           = trim((string) post('phone'));
            $businessName    = trim((string) post('business_name'));
            $businessType    = trim((string) post('business_type'));
            $businessWebsite = trim((string) post('business_website'));
            $address         = trim((string) post('address'));
            $city            = trim((string) post('city'));
            $state           = trim((string) post('state'));
            $pincode         = trim((string) post('pincode'));
            $panNumber       = strtoupper(trim((string) post('pan_number')));
            $gstNumber       = strtoupper(trim((string) post('gst_number')));

            if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,25}$/', $phone)) {
                $error = 'Please enter a valid phone or mobile number.';
            } elseif ($businessWebsite !== '' && !filter_var($businessWebsite, FILTER_VALIDATE_URL) && !preg_match('/^[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}(\/.*)?$/', $businessWebsite)) {
                $error = 'Please enter a valid website address.';
            } else {
                if ($businessWebsite !== '' && !preg_match('#^https?://#i', $businessWebsite)) {
                    $businessWebsite = 'https://' . $businessWebsite;
                }

                $stmt = db()->prepare('UPDATE users SET 
                    phone = ?, business_name = ?, business_type = ?, business_website = ?, 
                    address = ?, city = ?, state = ?, pincode = ?, 
                    pan_number = ?, gst_number = ? 
                    WHERE id = ?');
                $stmt->execute([
                    $phone !== '' ? $phone : null,
                    $businessName !== '' ? $businessName : null,
                    $businessType !== '' ? $businessType : null,
                    $businessWebsite !== '' ? $businessWebsite : null,
                    $address !== '' ? $address : null,
                    $city !== '' ? $city : null,
                    $state !== '' ? $state : null,
                    $pincode !== '' ? $pincode : null,
                    $panNumber !== '' ? $panNumber : null,
                    $gstNumber !== '' ? $gstNumber : null,
                    $user['id']
                ]);
                $saved = 'Business details and operating address updated successfully.';
            }
        }

        /* ---------- Email change (RESTRICTED: Admin Only) ---------- */
        if ($action === 'email') {
            $error = 'Email addresses cannot be modified directly by users. Only Platform Administrators have authorization to change registered emails. Please contact support.';
        }

        /* ---------- Avatar preset ---------- */
        if ($action === 'avatar') {
            $posted  = (string) post('avatar_preset');
            $allowed = [];
            foreach (glob(APP_ROOT . '/assets/img/avatars/*.svg') ?: [] as $presetFile) {
                $allowed[] = 'assets/img/avatars/' . basename($presetFile);
            }
            if (!in_array($posted, $allowed, true)) {
                $error = 'Please choose an avatar from the gallery.';
            } else {
                db()->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$posted, $user['id']]);
                $saved = 'Profile avatar updated successfully.';
            }
        }

        /* ---------- Step 1: Request Password Change OTP ---------- */
        if ($action === 'request_password_otp') {
            $current = (string) post('current_password');
            $newPass = (string) post('new_password');
            $confirm = (string) post('confirm_password');

            if (!password_verify($current, (string) $user['password_hash'])) {
                $error = 'Current password is incorrect.';
            } elseif (mb_strlen($newPass) < 8) {
                $error = 'New password must be at least 8 characters long.';
            } elseif ($newPass !== $confirm) {
                $error = 'New passwords do not match.';
            } elseif (password_verify($newPass, (string) $user['password_hash'])) {
                $error = 'New password must be different from your current password.';
            } else {
                $otpRes = otp_send($user['email'], 'change_password', [
                    'new_hash' => password_hash($newPass, PASSWORD_DEFAULT),
                ]);

                if ($otpRes['ok']) {
                    $_SESSION['pwd_otp_active']   = true;
                    $_SESSION['pwd_otp_sent_at']  = time();
                    $_SESSION['pwd_otp_cooldown'] = $otpRes['retry_after'] ?? security_setting('otp_resend_cooldown_seconds', 30);
                    flash_set('success', 'A 6-digit verification code has been dispatched to your Gmail (' . htmlspecialchars($user['email']) . '). Please enter it below to authorize the password update.');
                    redirect('user/profile.php?tab=security');
                } else {
                    $error = $otpRes['error'] ?? 'Could not deliver verification OTP to your Gmail. Please try again.';
                }
            }
        }

        /* ---------- Step 2: Verify Password Change OTP ---------- */
        if ($action === 'verify_password_otp') {
            $otpCode = trim((string) post('otp_code'));
            if ($otpCode === '') {
                $error = 'Please enter the 6-digit verification code sent to your Gmail.';
            } else {
                $verifyRes = otp_verify($user['email'], 'change_password', $otpCode);
                if ($verifyRes['ok']) {
                    $payload  = $verifyRes['payload'] ?? [];
                    $newHash  = $payload['new_hash'] ?? null;

                    if (!$newHash) {
                        $error = 'Password authorization session expired. Please enter your passwords again.';
                        unset($_SESSION['pwd_otp_active'], $_SESSION['pwd_otp_sent_at'], $_SESSION['pwd_otp_cooldown']);
                    } else {
                        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                            ->execute([$newHash, $user['id']]);

                        unset($_SESSION['pwd_otp_active'], $_SESSION['pwd_otp_sent_at'], $_SESSION['pwd_otp_cooldown']);
                        flash_set('success', 'Your account security password has been changed successfully!');
                        redirect('user/profile.php?tab=security');
                    }
                } else {
                    $error = $verifyRes['error'] ?? 'Invalid verification code.';
                }
            }
        }

        /* ---------- Resend Password Change OTP ---------- */
        if ($action === 'resend_password_otp') {
            $resendRes = otp_resend($user['email'], 'change_password');
            if ($resendRes['ok']) {
                $_SESSION['pwd_otp_cooldown'] = $resendRes['retry_after'] ?? security_setting('otp_resend_cooldown_seconds', 30);
                $_SESSION['pwd_otp_sent_at']   = time();
                flash_set('success', 'A fresh 6-digit verification code has been dispatched to your Gmail.');
            } else {
                flash_set('error', $resendRes['error'] ?? 'Please wait before requesting another code.');
            }
            redirect('user/profile.php?tab=security');
        }

        /* ---------- Cancel Password Change OTP ---------- */
        if ($action === 'cancel_password_otp') {
            unset($_SESSION['pwd_otp_active'], $_SESSION['pwd_otp_sent_at'], $_SESSION['pwd_otp_cooldown']);
            db()->prepare('DELETE FROM email_otps WHERE email = ? AND purpose = "change_password"')->execute([$user['email']]);
            flash_set('success', 'Password update request cancelled.');
            redirect('user/profile.php?tab=security');
        }
    }
}

if ($saved) {
    flash_set('success', $saved);
    $tabParam = match($action) {
        'business' => 'business',
        'avatar'   => 'avatar',
        'email'    => 'email',
        'request_password_otp', 'verify_password_otp', 'resend_password_otp', 'cancel_password_otp' => 'security',
        default    => 'general',
    };
    redirect('user/profile.php?tab=' . $tabParam);
}

/* Tab resolution */
$activeTab = get('tab', 'general');
if (!in_array($activeTab, ['general', 'business', 'avatar', 'email', 'security'], true)) {
    $activeTab = 'general';
}
if ($error && isset($action)) {
    $activeTab = match($action) {
        'business' => 'business',
        'avatar'   => 'avatar',
        'email'    => 'email',
        'request_password_otp', 'verify_password_otp', 'resend_password_otp', 'cancel_password_otp' => 'security',
        default    => 'general',
    };
}

$pwdOtpActive = !empty($_SESSION['pwd_otp_active']);
$pwdCooldown = 0;
if ($pwdOtpActive) {
    $cooldownSec = (int) ($_SESSION['pwd_otp_cooldown'] ?? security_setting('otp_resend_cooldown_seconds', 30));
    $sentAt      = (int) ($_SESSION['pwd_otp_sent_at'] ?? time());
    $elapsed     = time() - $sentAt;
    $pwdCooldown = max(0, $cooldownSec - $elapsed);
}

/* refresh user record */
$user = current_user(true);

$pageTitle       = 'Merchant Profile · Settings';
$pageHeading     = 'Profile';
$pageSub         = 'Manage account credentials, receiving UPI VPA, and brand avatar.';
$hidePageHeading = true; // Sleek custom executive hero stage
include __DIR__ . '/includes/header.php';
?>

<!-- =========================================================
     EXECUTIVE PROFILE STAGE (Full-Width Like All Other Pages)
     ========================================================= -->
<?php
$userEmail = (string)($user['email'] ?? '');
$emailLen = strlen($userEmail);
$emailScaleClass = 'email-len-sm';
if ($emailLen > 28) {
    $emailScaleClass = 'email-len-xl';
} elseif ($emailLen > 22) {
    $emailScaleClass = 'email-len-lg';
} elseif ($emailLen > 16) {
    $emailScaleClass = 'email-len-md';
}
?>
<div class="profile-hero-stage" data-reveal>
    <div class="profile-hero-mesh" aria-hidden="true"></div>

    <div class="profile-hero-body">
        <div class="profile-hero-user">
            <!-- Master Avatar with Edit Badge -->
            <div class="profile-avatar-master">
                <div class="profile-avatar-circle">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= e(url($user['avatar'])) ?>" alt="Brand Avatar">
                    <?php else: ?>
                        <?= e(initials($user['name'])) ?>
                    <?php endif; ?>
                </div>
                <a href="?tab=avatar" data-profile-tab="avatar" class="profile-avatar-edit-badge" title="Change brand avatar">
                    <?= icon('image', 13) ?>
                </a>
            </div>

            <!-- User Info & Verified Chips -->
            <div class="profile-user-info">
                <div class="profile-user-head">
                    <h1 class="profile-user-name"><?= e($user['name']) ?></h1>
                    <span class="profile-verified-badge">
                        <span class="profile-verified-dot"></span>
                        <span>Active Merchant</span>
                    </span>
                </div>

                <div class="profile-meta-chips">
                    <span class="profile-chip profile-chip-mid" title="Unique Merchant Account ID">
                        <span class="chip-label">MID:</span>
                        <b class="chip-val"><?= e($user['merchant_id']) ?></b>
                        <button type="button" class="chip-copy-btn" data-copy="<?= e($user['merchant_id']) ?>" aria-label="Copy Merchant ID">
                            <?= icon('copy', 12) ?>
                        </button>
                    </span>

                    <span class="profile-chip profile-chip-upi" title="Primary Receiving UPI VPA">
                        <span class="chip-label">UPI:</span>
                        <b class="chip-val chip-val-upi"><?= e($user['upi_id'] ?: 'Unassigned') ?></b>
                        <?php if (!empty($user['upi_id'])): ?>
                        <button type="button" class="chip-copy-btn" data-copy="<?= e($user['upi_id']) ?>" aria-label="Copy UPI VPA">
                            <?= icon('copy', 12) ?>
                        </button>
                        <?php endif; ?>
                    </span>

                    <span class="profile-chip profile-chip-email <?= e($emailScaleClass) ?>" title="Account Email: <?= e($userEmail) ?>">
                        <?= icon('mail', 12) ?>
                        <span class="email-val"><?= e($userEmail) ?></span>
                    </span>

                    <?php if (!empty($user['business_name'])): ?>
                        <span class="profile-chip" title="Registered Business Name">
                            <?= icon('briefcase', 12) ?>
                            <b class="chip-val"><?= e($user['business_name']) ?></b>
                        </span>
                    <?php endif; ?>

                    <?php if (!empty($user['phone'])): ?>
                        <span class="profile-chip" title="Contact Number">
                            <?= icon('phone', 12) ?>
                            <span class="mono" style="font-size:11.5px;"><?= e($user['phone']) ?></span>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="profile-hero-actions">
            <a href="<?= url('user/api-keys.php') ?>" class="profile-hero-btn profile-hero-btn-primary">
                <?= icon('key', 13) ?> API Keys
            </a>
            <a href="<?= url('logout.php') ?>" class="profile-hero-btn profile-hero-btn-danger">
                <?= icon('logout', 13) ?> Sign Out
            </a>
        </div>
    </div>
</div>

<!-- =========================================================
     CENTERED PROFILE CONTAINER (Grand, Centered & Clean Layout)
     ========================================================= -->
<div class="profile-container-center">

    <!-- =========================================================
         SYSTEM ALERTS
         ========================================================= -->
    <?php if ($error): ?>
        <div class="dash-alert dash-alert-error" data-reveal style="margin-bottom:16px;">
            <div class="alert-icon-wrap"><?= icon('alert', 16) ?></div>
            <div class="alert-text-wrap" style="font-size:13px;"><strong>Notice:</strong> <?= e($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($f = flash_get()): ?>
        <div class="dash-alert dash-alert-success" data-reveal style="margin-bottom:16px;">
            <div class="alert-icon-wrap"><?= icon('check-circle', 16) ?></div>
            <div class="alert-text-wrap" style="font-size:13px;"><strong>Success:</strong> <?= e($f['message']) ?></div>
        </div>
    <?php endif; ?>

    <!-- =========================================================
         SEGMENTED TABS NAVIGATION (Clean, Uncluttered, Fast)
         ========================================================= -->
    <nav class="profile-tabs-nav" aria-label="Profile navigation sections" data-liquid-tabs data-reveal>
        <div class="tx-liquid-glider" aria-hidden="true">
            <span class="tx-glider-wave"></span>
        </div>
        <button type="button" class="profile-tab-btn <?= $activeTab === 'general' ? 'is-active active' : '' ?>" data-profile-tab="general">
            <?= icon('user', 14) ?>
            <span>Merchant Details</span>
        </button>
        <button type="button" class="profile-tab-btn <?= $activeTab === 'business' ? 'is-active active' : '' ?>" data-profile-tab="business">
            <?= icon('briefcase', 14) ?>
            <span>Business &amp; Address</span>
        </button>
        <button type="button" class="profile-tab-btn <?= $activeTab === 'avatar' ? 'is-active active' : '' ?>" data-profile-tab="avatar">
            <?= icon('image', 14) ?>
            <span>Profile Avatar</span>
        </button>
        <button type="button" class="profile-tab-btn <?= $activeTab === 'email' ? 'is-active active' : '' ?>" data-profile-tab="email">
            <?= icon('mail', 14) ?>
            <span>Account Email</span>
        </button>
        <button type="button" class="profile-tab-btn <?= $activeTab === 'security' ? 'is-active active' : '' ?>" data-profile-tab="security">
            <?= icon('lock', 14) ?>
            <span>Security &amp; Password</span>
        </button>
    </nav>

    <!-- =========================================================
         TAB PANEL 1: MERCHANT & SETTLEMENT DETAILS (VERTICALLY STACKED)
         ========================================================= -->
    <div class="profile-tab-panel <?= $activeTab === 'general' ? 'is-active' : '' ?>" id="panel-general" data-profile-panel="general">
        <div class="profile-stacked-wrap">
            <!-- 1. UPER (TOP CARD): Merchant & Payout Settings -->
            <div class="profile-grand-card">
                <div class="white-card-header">
                    <div>
                        <h3 class="white-card-title">
                            <span style="color:#2563eb;display:inline-flex;align-items:center;"><?= icon('user', 18) ?></span>
                            Merchant &amp; Payout Settings
                        </h3>
                    </div>
                </div>

                <form method="post" action="<?= url('user/profile.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="personal">

                    <div class="profile-fields-row" style="margin-bottom:20px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                <?= icon('user', 14) ?> Full Merchant / Brand Name
                            </label>
                            <div class="input-icon-group">
                                <span class="input-icon-prefix"><?= icon('user', 15) ?></span>
                                <input class="form-input input-with-icon" id="profileMerchantNameInput" name="name" value="<?= e($user['name']) ?>" placeholder="e.g. Prince Store" style="height:48px;font-size:14px;padding-left:42px !important;" required>
                            </div>
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                <?= icon('wallet', 14) ?> Primary Receiving UPI ID (VPA)
                            </label>
                            <div class="input-icon-group">
                                <span class="input-icon-prefix"><?= icon('wallet', 15) ?></span>
                                <input class="form-input input-with-icon" id="profileUpiIdInput" name="upi_id" placeholder="e.g. username@okhdfcbank" value="<?= e($user['upi_id'] ?? '') ?>" style="height:48px;font-size:14px;padding-left:42px !important;">
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:24px;">
                        <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                            <?= icon('shield', 14) ?> Merchant ID (System Identifier)
                        </label>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <input class="form-input mono readonly-field" value="<?= e($user['merchant_id']) ?>" readonly style="height:48px;font-size:14px;font-weight:600;">
                            <button type="button" class="btn btn-secondary" data-copy="<?= e($user['merchant_id']) ?>" style="height:48px;padding:0 20px;font-size:13.5px;gap:7px;flex-shrink:0;">
                                <?= icon('copy', 15) ?> Copy ID
                            </button>
                        </div>
                    </div>

                    <button class="btn btn-primary" type="submit" style="height:46px;padding:0 26px;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:8px;">
                        <?= icon('check', 16) ?> Save Merchant Details
                    </button>
                </form>
            </div>

            <!-- 2. NICHE (BOTTOM CARD): How Customers See Your Checkout (Live Preview) -->
            <div class="profile-grand-card checkout-preview-card">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;">
                    <div>
                        <div class="preview-badge-live">
                            <span class="mini-beacon"></span>
                            <span>Live Customer Preview</span>
                        </div>
                        <h4 class="card-inner-heading" style="font-size:16px;font-weight:700;margin:3px 0 3px;">How Customers See Your Checkout</h4>
                    </div>
                </div>

                <div class="preview-mock-bill">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid rgba(226,232,240,0.8);">
                        <div style="font-size:11.5px;font-weight:700;text-transform:uppercase;color:#2563eb;letter-spacing:0.04em;display:flex;align-items:center;gap:6px;">
                            <?= icon('zap', 14) ?> UPI Instant Payment Checkout
                        </div>
                        <div style="font-size:11.5px;color:#10b981;font-weight:600;display:flex;align-items:center;gap:5px;">
                            <?= icon('shield', 13) ?> 256-Bit SSL Encrypted
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px;">
                        <div style="display:flex;align-items:center;gap:14px;">
                            <div style="width:48px;height:48px;border-radius:12px;background:linear-gradient(135deg,#2563eb,#38bdf8);color:#ffffff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;flex-shrink:0;overflow:hidden;box-shadow:0 4px 12px rgba(37,99,235,0.25);">
                                <?php if (!empty($user['avatar'])): ?>
                                    <img src="<?= e(url($user['avatar'])) ?>" alt="Avatar" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <?= e(initials($user['name'])) ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="card-inner-heading" style="font-size:17px;font-weight:700;" id="previewMerchantName">
                                    <?= e($user['name']) ?>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;margin-top:3px;font-size:13px;font-family:'JetBrains Mono',monospace;">
                                    <span class="card-inner-desc">Pay To:</span>
                                    <span id="previewUpiId" style="color:#2563eb;font-weight:600;"><?= e($user['upi_id'] ?: 'merchant@upi') ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="preview-amount-card">
                            <div class="amount-label">Sample Amount</div>
                            <div class="amount-val">₹500.00</div>
                        </div>
                    </div>

                    <!-- Supported UPI Apps Row -->
                    <div class="preview-apps-card">
                        <span class="apps-label">Supported Apps:</span>
                        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                            <span class="dash-chip">Google Pay</span>
                            <span class="dash-chip">PhonePe</span>
                            <span class="dash-chip">Paytm</span>
                            <span class="dash-chip">BHIM UPI</span>
                            <span class="dash-chip">CRED</span>
                        </div>
                    </div>

                    <div style="margin-top:14px;padding-top:12px;border-top:1px dashed rgba(226,232,240,0.8);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <div style="font-size:12px;color:#059669;display:flex;align-items:center;gap:6px;font-weight:600;">
                            <?= icon('check-circle', 14) ?>
                            <span>Instant Direct Bank Settlement via UPI &middot; Zero Gateway Fee</span>
                        </div>
                        <div class="card-inner-desc" style="font-size:11.5px;">
                            Dynamic QR &middot; UTR Auto-Reconciled
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         TAB PANEL: BUSINESS & OPERATING ADDRESS DETAILS
         ========================================================= -->
    <div class="profile-tab-panel <?= $activeTab === 'business' ? 'is-active' : '' ?>" id="panel-business" data-profile-panel="business">
        <div class="profile-stacked-wrap">
            <div class="profile-grand-card">
                <div class="white-card-header">
                    <div>
                        <h3 class="white-card-title">
                            <span style="color:#2563eb;display:inline-flex;align-items:center;"><?= icon('briefcase', 18) ?></span>
                            Business Profile &amp; Registered Address
                        </h3>
                        <p class="card-inner-desc" style="margin:4px 0 0;font-size:13px;">
                            Official commercial brand identity, active website or app, customer support hotline, and operational physical address.
                        </p>
                    </div>
                </div>

                <form method="post" action="<?= url('user/profile.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="business">

                    <!-- Section 1: Business Identity & Store Presence -->
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                        <?= icon('building', 13) ?> Commercial Brand &amp; Digital Store
                    </div>

                    <div class="profile-fields-row" style="margin-bottom:16px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                <?= icon('briefcase', 14) ?> Business / Store Name
                            </label>
                            <div class="input-icon-group">
                                <span class="input-icon-prefix"><?= icon('briefcase', 15) ?></span>
                                <input class="form-input input-with-icon" name="business_name" value="<?= e($user['business_name'] ?? '') ?>" placeholder="e.g. Apex Store Pvt Ltd" style="height:48px;font-size:14px;padding-left:42px !important;">
                            </div>
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                <?= icon('blocks', 14) ?> Business Category
                            </label>
                            <select class="form-select" name="business_type" style="height:48px;font-size:14px;">
                                <?php
                                $bTypes = [
                                    '' => '— Select Business Type —',
                                    'E-Commerce & Retail Store' => 'E-Commerce & Retail Store',
                                    'Digital Products & SaaS' => 'Digital Products & SaaS',
                                    'IT Services & Software' => 'IT Services & Software Agency',
                                    'Freelancer & Consulting' => 'Freelancer & Consulting',
                                    'Education & EdTech' => 'Education & EdTech',
                                    'Gaming & Entertainment' => 'Gaming & Entertainment',
                                    'Hospitality & Travel' => 'Hospitality & Travel',
                                    'Other' => 'Other Business / Enterprise'
                                ];
                                $curType = trim((string)($user['business_type'] ?? ''));
                                foreach ($bTypes as $val => $lbl):
                                    $isSel = ($curType === $val) 
                                        || ($val !== '' && str_starts_with($curType, $val))
                                        || ($curType !== '' && str_starts_with($val, $curType));
                                ?>
                                    <option value="<?= e($val) ?>" <?= $isSel ? 'selected' : '' ?>><?= e($lbl) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="profile-fields-row" style="margin-bottom:20px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                <?= icon('globe', 14) ?> Business Website / App URL
                            </label>
                            <div class="input-icon-group">
                                <span class="input-icon-prefix"><?= icon('globe', 15) ?></span>
                                <input class="form-input input-with-icon" name="business_website" value="<?= e($user['business_website'] ?? '') ?>" placeholder="https://yourbrand.com" style="height:48px;font-size:14px;padding-left:42px !important;">
                            </div>
                            <span class="form-help" style="font-size:11.5px;color:#64748b;margin-top:4px;display:block;">Your online store, SaaS application, or business portal.</span>
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                <?= icon('phone', 14) ?> Contact Phone / WhatsApp Number
                            </label>
                            <div class="input-icon-group">
                                <span class="input-icon-prefix"><?= icon('phone', 15) ?></span>
                                <input class="form-input input-with-icon" name="phone" value="<?= e($user['phone'] ?? '') ?>" placeholder="e.g. +91 98765 43210" style="height:48px;font-size:14px;padding-left:42px !important;">
                            </div>
                            <span class="form-help" style="font-size:11.5px;color:#64748b;margin-top:4px;display:block;">Official merchant phone or WhatsApp customer support line.</span>
                        </div>
                    </div>

                    <!-- Section 2: Registered Physical / Operating Address -->
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin:24px 0 12px;display:flex;align-items:center;justify-content:space-between;gap:8px;border-top:1px solid rgba(226,232,240,0.8);padding-top:20px;flex-wrap:wrap;">
                        <span style="display:flex;align-items:center;gap:6px;"><?= icon('map-pin', 13) ?> Operating / Physical Location</span>
                        <button type="button" class="btn btn-secondary btn-xs" id="btnAutoDetectLocation" style="font-size:11.5px;padding:4px 10px;gap:6px;border-radius:6px;background:rgba(37,99,235,0.08);color:#2563eb;border-color:rgba(37,99,235,0.25);font-weight:600;">
                            <?= icon('map-pin', 13) ?> Auto-Detect Address from Device GPS
                        </button>
                    </div>

                    <div class="form-group" style="margin-bottom:16px;">
                        <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                            <?= icon('map-pin', 14) ?> Street Address / Building / Suite
                        </label>
                        <textarea class="form-input form-textarea" name="address" rows="3" placeholder="e.g. Office #304, Cyber City Phase 2, Ring Road" style="width:100% !important;min-height:76px;font-size:14px;line-height:1.5;padding:10px 14px;box-sizing:border-box;resize:vertical;"><?= e($user['address'] ?? '') ?></textarea>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:16px;margin-bottom:24px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                City / District
                            </label>
                            <input class="form-input" name="city" value="<?= e($user['city'] ?? '') ?>" placeholder="e.g. Mumbai" style="height:48px;font-size:14px;">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                State / Province
                            </label>
                            <input class="form-input" name="state" value="<?= e($user['state'] ?? '') ?>" placeholder="e.g. Maharashtra" style="height:48px;font-size:14px;">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                PIN / Postal Code
                            </label>
                            <input class="form-input mono" name="pincode" value="<?= e($user['pincode'] ?? '') ?>" placeholder="e.g. 400001" maxlength="10" style="height:48px;font-size:14px;">
                        </div>
                    </div>

                    <!-- Section 3: Tax & Legal Compliance (Optional) -->
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin:24px 0 12px;display:flex;align-items:center;gap:6px;border-top:1px solid rgba(226,232,240,0.8);padding-top:20px;">
                        <?= icon('file-text', 13) ?> Tax &amp; Regulatory Identifiers (Optional)
                    </div>

                    <div class="profile-fields-row" style="margin-bottom:24px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                <?= icon('file-text', 14) ?> Permanent Account Number (PAN)
                            </label>
                            <input class="form-input mono" name="pan_number" value="<?= e($user['pan_number'] ?? '') ?>" placeholder="e.g. ABCDE1234F" maxlength="10" style="height:48px;font-size:14px;text-transform:uppercase;">
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                <?= icon('shield', 14) ?> Goods &amp; Services Tax ID (GSTIN)
                            </label>
                            <input class="form-input mono" name="gst_number" value="<?= e($user['gst_number'] ?? '') ?>" placeholder="e.g. 27ABCDE1234F1Z5" maxlength="15" style="height:48px;font-size:14px;text-transform:uppercase;">
                        </div>
                    </div>

                    <button class="btn btn-primary" type="submit" style="height:46px;padding:0 26px;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:8px;">
                        <?= icon('check', 16) ?> Save Business &amp; Address Details
                    </button>
                </form>
            </div>

            <!-- Verification & Compliance Summary Card -->
            <div class="profile-grand-card" style="margin-top:0;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="width:38px;height:38px;border-radius:10px;background:rgba(37,99,235,0.1);color:#2563eb;display:flex;align-items:center;justify-content:center;">
                            <?= icon('shield', 20) ?>
                        </div>
                        <div>
                            <div style="font-size:14px;font-weight:700;color:var(--color-ink-base);">Merchant Record Status</div>
                            <div style="font-size:12px;color:#64748b;">This information is securely synchronized with Platform Administrators for account verification.</div>
                        </div>
                    </div>
                    <?php
                    $isComplete = !empty($user['phone']) && !empty($user['address']) && !empty($user['business_name']);
                    ?>
                    <span class="badge <?= $isComplete ? 'badge-success' : 'badge-neutral' ?>" style="font-size:11.5px;padding:5px 12px;gap:6px;">
                        <?= $isComplete ? icon('check-circle', 12) . ' Complete Profile' : icon('clock', 12) . ' Incomplete Details' ?>
                    </span>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:14px;background:rgba(0,0,0,0.02);padding:16px;border-radius:12px;border:1px solid rgba(226,232,240,0.8);">
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;font-weight:600;color:#64748b;letter-spacing:0.04em;">Business Entity</div>
                        <div style="font-size:13.5px;font-weight:600;margin-top:3px;color:var(--color-ink-base);"><?= e($user['business_name'] ?: ($user['name'] ?: 'Not configured')) ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;font-weight:600;color:#64748b;letter-spacing:0.04em;">Hotline / Phone</div>
                        <div style="font-size:13.5px;font-weight:600;margin-top:3px;color:var(--color-ink-base);"><?= e($user['phone'] ?: 'No phone provided') ?></div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;font-weight:600;color:#64748b;letter-spacing:0.04em;">Operating City / State</div>
                        <div style="font-size:13.5px;font-weight:600;margin-top:3px;color:var(--color-ink-base);">
                            <?= e(($user['city'] ?? '') ? ($user['city'] . ($user['state'] ? ', ' . $user['state'] : '')) : 'No location specified') ?>
                        </div>
                    </div>
                    <div>
                        <div style="font-size:11px;text-transform:uppercase;font-weight:600;color:#64748b;letter-spacing:0.04em;">Official Website</div>
                        <div style="font-size:13.5px;font-weight:600;margin-top:3px;">
                            <?php if (!empty($user['business_website'])): ?>
                                <a href="<?= e($user['business_website']) ?>" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                                    <?= icon('globe', 13) ?> Visit Store ↗
                                </a>
                            <?php else: ?>
                                <span style="color:#94a3b8;">None added</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         TAB PANEL 2: BRAND QR LOGO & AVATAR (VERTICALLY STACKED)
         ========================================================= -->
    <div class="profile-tab-panel <?= $activeTab === 'avatar' ? 'is-active' : '' ?>" id="panel-avatar" data-profile-panel="avatar">
        <div class="profile-stacked-wrap">
            <!-- 1. UPER (TOP CARD): Avatar Preset Gallery -->
            <div class="profile-grand-card">
                <div class="white-card-header">
                    <div>
                        <h3 class="white-card-title">
                            <span style="color:#2563eb;display:inline-flex;align-items:center;"><?= icon('image', 18) ?></span>
                            Choose Profile Avatar
                        </h3>
                    </div>
                </div>

                <form method="post" action="<?= url('user/profile.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="avatar">

                    <?php
                    $avatarStyleLabels = [
                        'avataaars'  => 'Classic',
                        'adventurer' => 'Adventurer',
                        'big-ears'   => 'Big Ears',
                        'lorelei'    => 'Lorelei',
                        'micah'      => 'Micah',
                        'miniavs'    => 'Mini',
                        'notionists' => 'Notionist',
                        'personas'   => 'Persona',
                        'fun-emoji'  => 'Emoji',
                        'bottts'     => 'Robot',
                    ];
                    $avatarPresets = [];
                    foreach (glob(APP_ROOT . '/assets/img/avatars/*.svg') ?: [] as $presetFile) {
                        $presetName  = basename($presetFile);
                        $presetStyle = preg_replace('/-\d+\.svg$/', '', $presetName);
                        $avatarPresets[] = [
                            'path'  => 'assets/img/avatars/' . $presetName,
                            'style' => $presetStyle,
                            'label' => $avatarStyleLabels[$presetStyle] ?? ucfirst($presetStyle),
                        ];
                    }
                    sort($avatarPresets);
                    $avatarStyles = [];
                    foreach ($avatarPresets as $presetItem) {
                        $avatarStyles[$presetItem['style']] = $presetItem['label'];
                    }
                    ?>

                    <div class="avatar-preset-filters">
                        <button type="button" class="avatar-filter-chip is-active" data-avatar-filter="all">All (<?= count($avatarPresets) ?>)</button>
                        <?php foreach ($avatarStyles as $styleKey => $styleLabel): ?>
                            <button type="button" class="avatar-filter-chip" data-avatar-filter="<?= e($styleKey) ?>"><?= e($styleLabel) ?></button>
                        <?php endforeach; ?>
                    </div>

                    <div class="avatar-preset-scroll">
                        <div class="avatar-preset-grid">
                            <?php foreach ($avatarPresets as $presetItem): ?>
                                <label class="avatar-preset <?= $user['avatar'] === $presetItem['path'] ? 'is-selected' : '' ?>" data-style="<?= e($presetItem['style']) ?>">
                                    <input type="radio" name="avatar_preset" value="<?= e($presetItem['path']) ?>" <?= $user['avatar'] === $presetItem['path'] ? 'checked' : '' ?>>
                                    <img src="<?= e(url($presetItem['path'])) ?>" alt="<?= e($presetItem['label']) ?> avatar" loading="lazy">
                                    <span class="avatar-preset-check"><?= icon('check', 12) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button class="btn btn-primary" type="submit" style="height:46px;padding:0 26px;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:8px;">
                        <?= icon('check', 15) ?> Save Avatar
                    </button>
                </form>
            </div>

            <!-- 2. NICHE (BOTTOM CARD): Mock QR Preview with Centered Logo -->
            <div class="profile-grand-card brand-qr-box">
                <h4 class="card-inner-heading" style="font-size:16px;font-weight:700;margin:0 0 18px;">Dynamic QR Center Preview</h4>

                <div class="mock-qr-stage" style="width:190px;height:190px;">
                    <!-- SVG High-Density Stylized QR Pattern -->
                    <svg width="164" height="164" viewBox="0 0 100 100" fill="#0f172a" style="opacity:0.85;">
                        <rect x="5" y="5" width="26" height="26" rx="4" fill="none" stroke="#0f172a" stroke-width="5"/>
                        <rect x="12" y="12" width="12" height="12" rx="2" fill="#0f172a"/>
                        <rect x="69" y="5" width="26" height="26" rx="4" fill="none" stroke="#0f172a" stroke-width="5"/>
                        <rect x="76" y="12" width="12" height="12" rx="2" fill="#0f172a"/>
                        <rect x="5" y="69" width="26" height="26" rx="4" fill="none" stroke="#0f172a" stroke-width="5"/>
                        <rect x="12" y="76" width="12" height="12" rx="2" fill="#0f172a"/>
                        <rect x="36" y="8" width="8" height="8" rx="1"/>
                        <rect x="48" y="14" width="8" height="8" rx="1"/>
                        <rect x="36" y="24" width="8" height="8" rx="1"/>
                        <rect x="10" y="38" width="8" height="8" rx="1"/>
                        <rect x="22" y="44" width="8" height="8" rx="1"/>
                        <rect x="80" y="38" width="8" height="8" rx="1"/>
                        <rect x="68" y="46" width="8" height="8" rx="1"/>
                        <rect x="40" y="68" width="8" height="8" rx="1"/>
                        <rect x="52" y="76" width="8" height="8" rx="1"/>
                        <rect x="68" y="80" width="8" height="8" rx="1"/>
                        <rect x="80" y="70" width="8" height="8" rx="1"/>
                    </svg>

                    <!-- Center Brand Badge -->
                    <div class="mock-qr-avatar-badge" style="width:44px;height:44px;">
                        <?php if (!empty($user['avatar'])): ?>
                            <img src="<?= e(url($user['avatar'])) ?>" id="mockQrAvatarImg" alt="Brand Logo">
                        <?php else: ?>
                            <span id="mockQrAvatarText"><?= e(initials($user['name'])) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="font-size:12.5px;font-weight:600;color:#2563eb;display:flex;align-items:center;gap:6px;margin-top:10px;">
                    <?= icon('check-circle', 14) ?>
                    <span>Instant Merchant Brand Recognition on Customer Mobile</span>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         TAB PANEL 3: ACCOUNT EMAIL SETTINGS (VERTICALLY STACKED)
         ========================================================= -->
    <div class="profile-tab-panel <?= $activeTab === 'email' ? 'is-active' : '' ?>" id="panel-email" data-profile-panel="email">
        <div class="profile-stacked-wrap">
            <!-- 1. UPER (TOP CARD): Email Form -->
            <div class="profile-grand-card">
                <div class="white-card-header">
                    <div>
                        <h3 class="white-card-title">
                            <span style="color:#2563eb;display:inline-flex;align-items:center;"><?= icon('mail', 18) ?></span>
                            Account Login Email
                        </h3>
                    </div>
                </div>

                <div class="verified-email-box">
                    <div>
                        <div class="email-label">Current Verified Email</div>
                        <div class="email-display">
                            <?= e($user['email']) ?>
                        </div>
                    </div>
                    <span class="badge badge-success" style="font-weight:600;font-size:11.5px;display:inline-flex;align-items:center;gap:5px;padding:4px 10px;">
                        <?= icon('check', 12) ?> Active &amp; Verified
                    </span>
                </div>

                <div style="margin-top:20px;padding:20px 22px;background:rgba(239,68,68,0.04);border:1.5px solid rgba(239,68,68,0.2);border-radius:12px;display:flex;align-items:flex-start;gap:16px;">
                    <div style="color:#ef4444;margin-top:2px;flex-shrink:0;">
                        <?= icon('lock', 22) ?>
                    </div>
                    <div>
                        <div style="font-weight:700;font-size:14.5px;color:#b91c1c;margin-bottom:6px;display:flex;align-items:center;gap:8px;">
                            <span>Email Change Restricted (Admin Authorization Only)</span>
                            <span class="badge" style="background:rgba(239,68,68,0.12);color:#b91c1c;font-size:11px;padding:2px 8px;font-weight:700;">Locked</span>
                        </div>
                        <p style="font-size:13px;color:#64748b;margin:0 0 16px;line-height:1.6;">
                            Merchant account security, payment settlement integrity, aur fraud protection ke liye registered email address user portal se change nahi kiya ja sakta. Account email sirf <b>Platform Administrator</b> hi modify kar sakte hain.
                        </p>
                        <a href="mailto:<?= e(SITE_EMAIL) ?>?subject=Email%20Change%20Request%20-%20Merchant%20<?= urlencode($user['merchant_id']) ?>" class="btn btn-secondary btn-sm flex items-center gap-2" style="font-size:12.5px;padding:9px 16px;display:inline-flex;">
                            <?= icon('mail', 14) ?> Contact Support to Request Email Change (<?= e(SITE_EMAIL) ?>)
                        </a>
                    </div>
                </div>
            </div>

            <!-- 2. NICHE (BOTTOM CARD): Security & Notification Policies -->
            <div class="profile-grand-card" data-reveal>
                <div class="white-card-header" style="margin-bottom:18px;">
                    <h3 class="white-card-title" style="font-size:16px;display:flex;align-items:center;gap:8px;">
                        <?= icon('shield', 18, 'style="color:#2563eb;"') ?> Security &amp; Delivery Policies
                    </h3>
                </div>

                <div class="integ-security-box">
                    <div class="integ-security-row">
                        <div class="integ-security-icon" style="background:rgba(34,197,94,0.16);color:#4ade80;">
                            <?= icon('check-circle', 15) ?>
                        </div>
                        <div>
                            <div class="integ-security-title" style="font-size:13.5px;">Account Recovery &amp; MFA</div>
                            <p class="integ-security-desc" style="font-size:12.5px;">Primary destination for password resets and critical authorization tokens.</p>
                        </div>
                    </div>

                    <div class="integ-security-row">
                        <div class="integ-security-icon" style="background:rgba(56,189,248,0.16);color:#38bdf8;">
                            <?= icon('mail', 15) ?>
                        </div>
                        <div>
                            <div class="integ-security-title" style="font-size:13.5px;">Daily Reconciliation Reports</div>
                            <p class="integ-security-desc" style="font-size:12.5px;">Automated daily transaction summaries and bank settlement alerts.</p>
                        </div>
                    </div>

                    <div class="integ-security-row">
                        <div class="integ-security-icon" style="background:rgba(168,85,247,0.16);color:#c084fc;">
                            <?= icon('alert', 15) ?>
                        </div>
                        <div>
                            <div class="integ-security-title" style="font-size:13.5px;">Critical Incident Dispatches</div>
                            <p class="integ-security-desc" style="font-size:12.5px;">Immediate notification in case of unknown browser sign-ins or key revocations.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- =========================================================
         TAB PANEL 4: SECURITY & PASSWORD (VERTICALLY STACKED)
         ========================================================= -->
    <div class="profile-tab-panel <?= $activeTab === 'security' ? 'is-active' : '' ?>" id="panel-security" data-profile-panel="security">
        <div class="profile-stacked-wrap">
            <?php if ($pwdOtpActive): ?>
            <!-- STEP 2: ENTER GMAIL OTP TO CONFIRM PASSWORD CHANGE -->
            <div class="profile-grand-card" style="border:1.5px solid rgba(37,99,235,0.3);background:linear-gradient(180deg,rgba(37,99,235,0.02) 0%,#ffffff 100%);">
                <div class="white-card-header">
                    <div>
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                            <span class="badge badge-primary" style="font-size:11px;padding:3px 10px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">Step 2 of 2: Gmail Verification</span>
                        </div>
                        <h3 class="white-card-title" style="display:flex;align-items:center;gap:8px;">
                            <span style="color:#2563eb;"><?= icon('shield', 18) ?></span>
                            Enter 6-Digit Authorization Code
                        </h3>
                        <p class="white-card-sub" style="font-size:13px;line-height:1.5;">
                            A 6-digit security verification code has been dispatched to <b><?= e($user['email']) ?></b>. Enter the code below to complete your password update.
                        </p>
                    </div>
                </div>

                <form method="post" action="<?= url('user/profile.php') ?>" id="formVerifyPwdOtp">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="verify_password_otp">

                    <div class="form-group" style="margin-bottom:22px;text-align:center;max-width:320px;margin-left:auto;margin-right:auto;">
                        <label class="form-label" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:10px;display:block;">
                            <?= icon('key', 14) ?> 6-Digit Security OTP
                        </label>
                        <input class="form-input" id="pwd_otp_code" name="otp_code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="------" autofocus required style="height:54px;font-size:28px;font-family:monospace;letter-spacing:10px;text-align:center;font-weight:800;border:2px solid #2563eb;color:#1e40af;border-radius:12px;">
                        <span class="text-mute" style="font-size:11.5px;margin-top:6px;display:block;">Code expires in 10 minutes</span>
                    </div>

                    <div style="display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
                        <button class="btn btn-primary" type="submit" id="btnVerifyPwd" style="height:46px;padding:0 26px;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:8px;">
                            <?= icon('check-circle', 16) ?> Verify OTP &amp; Update Password
                        </button>
                    </div>
                </form>

                <div style="display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;border-top:1px solid #f1f5f9;padding-top:16px;margin-top:10px;">
                    <form method="post" action="<?= url('user/profile.php') ?>" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resend_password_otp">
                        <button type="submit" id="btnResendPwdOtp" class="btn btn-secondary btn-sm" <?= $pwdCooldown > 0 ? 'disabled' : '' ?> style="font-size:12.5px;">
                            <?= icon('refresh', 13) ?> Resend Code <?= $pwdCooldown > 0 ? "(<span id=\"pwdResendTimer\">{$pwdCooldown}</span>s)" : '' ?>
                        </button>
                    </form>

                    <form method="post" action="<?= url('user/profile.php') ?>" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="cancel_password_otp">
                        <button type="submit" class="btn btn-sm flex items-center gap-2" style="background:transparent;border:1px solid #cbd5e1;color:#64748b;font-size:12.5px;padding:8px 14px;border-radius:8px;">
                            <?= icon('x', 13) ?> Cancel &amp; Go Back
                        </button>
                    </form>
                </div>
            </div>

            <?php else: ?>
            <!-- STEP 1: PASSWORD INPUT FORM (TRIGGERS GMAIL OTP) -->
            <div class="profile-grand-card">
                <div class="white-card-header">
                    <div>
                        <h3 class="white-card-title">
                            <span style="color:#2563eb;display:inline-flex;align-items:center;"><?= icon('lock', 18) ?></span>
                            Update Security Password
                        </h3>
                        <p class="white-card-sub">Protected by 2-Step Gmail OTP verification for merchant account safety.</p>
                    </div>
                </div>

                <div style="margin-bottom:20px;padding:12px 16px;background:rgba(37,99,235,0.06);border:1px solid rgba(37,99,235,0.2);border-radius:10px;font-size:12.5px;color:#1e40af;display:flex;align-items:center;gap:10px;">
                    <span style="font-size:18px;"><?= icon('shield', 18) ?></span>
                    <span><b>2-Step Verification Active:</b> Password change karne ke liye aapke Gmail (<b><?= e($user['email']) ?></b>) par ek 6-digit OTP jayega, jise verify karne ke baad hi password update hoga.</span>
                </div>

                <form method="post" action="<?= url('user/profile.php') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="request_password_otp">

                    <div class="form-group" style="margin-bottom:18px;">
                        <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                            <?= icon('lock', 14) ?> Current Password
                        </label>
                        <div class="input-with-action">
                            <input class="form-input" id="passCurrentInput" type="password" name="current_password" placeholder="Enter current password" style="height:48px;font-size:14px;" required autocomplete="current-password">
                            <button type="button" class="field-action-btn pass-toggle-action" data-target="passCurrentInput" aria-label="Toggle visibility">
                                <?= icon('eye', 15) ?>
                            </button>
                        </div>
                    </div>

                    <div class="profile-fields-row" style="margin-bottom:22px;">
                        <div class="form-group" style="margin:0;">
                            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                                <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin:0;">
                                    <?= icon('key', 14) ?> New Password
                                </label>
                                <span id="passStrengthText" style="font-size:11.5px;font-weight:700;"></span>
                            </div>
                            <div class="input-with-action">
                                <input class="form-input" id="profileNewPassInput" type="password" name="new_password" placeholder="Min 8 characters" style="height:48px;font-size:14px;" required autocomplete="new-password">
                                <button type="button" class="field-action-btn pass-toggle-action" data-target="profileNewPassInput" aria-label="Toggle visibility">
                                    <?= icon('eye', 15) ?>
                                </button>
                            </div>
                            <!-- Live Strength Bar -->
                            <div class="pass-strength-bar">
                                <div class="pass-strength-fill" id="passStrengthFill"></div>
                            </div>
                        </div>

                        <div class="form-group" style="margin:0;">
                            <label class="form-label" style="font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;margin-bottom:8px;">
                                <?= icon('check', 14) ?> Confirm New Password
                            </label>
                            <div class="input-with-action">
                                <input class="form-input" id="passConfirmInput" type="password" name="confirm_password" placeholder="Repeat new password" style="height:48px;font-size:14px;" required autocomplete="new-password">
                                <button type="button" class="field-action-btn pass-toggle-action" data-target="passConfirmInput" aria-label="Toggle visibility">
                                    <?= icon('eye', 15) ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <button class="btn btn-primary" type="submit" style="height:46px;padding:0 26px;font-size:14px;font-weight:600;display:inline-flex;align-items:center;gap:8px;">
                        <?= icon('send', 15) ?> Send Verification Code to Gmail
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- 2. NICHE (MIDDLE CARD): Security Vault Architecture -->
            <div class="profile-grand-card" data-reveal>
                <div class="white-card-header" style="margin-bottom:18px;">
                    <h3 class="white-card-title" style="font-size:16px;display:flex;align-items:center;gap:8px;">
                        <?= icon('shield', 18, 'style="color:#2563eb;"') ?> Security Vault Architecture
                    </h3>
                </div>

                <div class="integ-security-box">
                    <div class="integ-security-row">
                        <div class="integ-security-icon" style="background:rgba(34,197,94,0.16);color:#4ade80;">
                            <?= icon('check-circle', 15) ?>
                        </div>
                        <div>
                            <div class="integ-security-title" style="font-size:13.5px;">BCrypt Password Protection</div>
                            <p class="integ-security-desc" style="font-size:12.5px;">Salted with adaptive cost factor against brute-force attacks.</p>
                        </div>
                    </div>

                    <div class="integ-security-row">
                        <div class="integ-security-icon" style="background:rgba(56,189,248,0.16);color:#38bdf8;">
                            <?= icon('plug', 15) ?>
                        </div>
                        <div>
                            <div class="integ-security-title" style="font-size:13.5px;">AES-256-GCM Vault</div>
                            <p class="integ-security-desc" style="font-size:12.5px;">API tokens and merchant credentials encrypted at rest.</p>
                        </div>
                    </div>

                    <div class="integ-security-row">
                        <div class="integ-security-icon" style="background:rgba(168,85,247,0.16);color:#c084fc;">
                            <?= icon('key', 15) ?>
                        </div>
                        <div>
                            <div class="integ-security-title" style="font-size:13.5px;">HMAC Webhooks &amp; Anti-CSRF</div>
                            <p class="integ-security-desc" style="font-size:12.5px;">Signed outbound payloads and protected form tokens.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 3. NICHE (BOTTOM CARD): Danger Zone -->
            <div class="danger-zone-card" style="margin-top:0;">
                <div>
                    <b style="font-size:13.5px;color:#dc2626;display:flex;align-items:center;gap:7px;">
                        <?= icon('alert', 16) ?> Active Session Sign Out
                    </b>
                    <p style="margin:3px 0 0;font-size:12.5px;color:#64748b;">
                        Terminate your merchant dashboard session on this device.
                    </p>
                </div>
                <a href="<?= url('logout.php') ?>" class="btn btn-danger btn-sm" style="font-size:12.5px;padding:8px 16px;gap:7px;">
                    <?= icon('logout', 14) ?> Sign Out
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-scale long email address so it fits seamlessly on all phone screens
(function() {
    function autoFitEmail() {
        var el = document.querySelector('.profile-chip-email .email-val');
        if (!el) return;
        var parent = el.closest('.profile-chip-email');
        if (!parent) return;
        var maxW = parent.clientWidth > 0 ? (parent.clientWidth - 26) : 180;
        var fs = parseFloat(window.getComputedStyle(el).fontSize) || 11;
        while (el.scrollWidth > maxW && fs > 7.2) {
            fs -= 0.4;
            el.style.fontSize = fs + 'px';
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoFitEmail);
    } else {
        autoFitEmail();
    }
    window.addEventListener('resize', autoFitEmail);
})();

// Auto-submit and timer for password change OTP
(function() {
    var otpInput = document.getElementById('pwd_otp_code');
    var verifyForm = document.getElementById('formVerifyPwdOtp');
    if (otpInput && verifyForm) {
        otpInput.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length === 6) {
                verifyForm.submit();
            }
        });
    }

    var timeLeft = <?= (int) $pwdCooldown ?>;
    var resendBtn = document.getElementById('btnResendPwdOtp');
    var timerSpan = document.getElementById('pwdResendTimer');
    if (timeLeft > 0 && resendBtn && timerSpan) {
        var interval = setInterval(function() {
            timeLeft--;
            if (timeLeft <= 0) {
                clearInterval(interval);
                resendBtn.removeAttribute('disabled');
                resendBtn.innerHTML = '<?= icon("refresh", 13) ?> Resend Code';
            } else {
                timerSpan.textContent = timeLeft;
            }
        }, 1000);
    }
})();

// Auto-Detect Current GPS Address & Autofill Form Fields
(function() {
    var btn = document.getElementById('btnAutoDetectLocation');
    if (!btn) return;

    btn.addEventListener('click', function() {
        if (!('geolocation' in navigator)) {
            alert('GPS geolocation is not supported by this browser.');
            return;
        }

        var origHtml = btn.innerHTML;
        btn.innerHTML = '<?= icon("loader", 13) ?> Detecting Exact GPS...';
        btn.disabled = true;

        navigator.geolocation.getCurrentPosition(function(pos) {
            var lat = pos.coords.latitude;
            var lng = pos.coords.longitude;
            var acc = pos.coords.accuracy;
            var alt = pos.coords.altitude;

            btn.innerHTML = '<?= icon("loader", 13) ?> Resolving Street Address...';

            // High-precision reverse geocode query
            var reverseUrl = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&zoom=18&addressdetails=1';
            fetch(reverseUrl)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.address) {
                        var a = data.address;
                        var parts = [];
                        if (a.house_number) parts.push(a.house_number);
                        if (a.building) parts.push(a.building);
                        if (a.road) parts.push(a.road);
                        if (a.suburb || a.neighbourhood) parts.push(a.suburb || a.neighbourhood);
                        var street = parts.join(', ');

                        var city = a.city || a.town || a.village || a.county || a.state_district || '';
                        var state = a.state || '';
                        var postCode = a.postcode || '';

                        var streetEl = document.querySelector('textarea[name="address"]');
                        var cityEl   = document.querySelector('input[name="city"]');
                        var stateEl  = document.querySelector('input[name="state"]');
                        var pinEl    = document.querySelector('input[name="pincode"]');

                        if (streetEl && street) streetEl.value = street;
                        if (cityEl && city) cityEl.value = city;
                        if (stateEl && state) stateEl.value = state;
                        if (pinEl && postCode) pinEl.value = postCode;
                    }

                    // Transmit GPS telemetry to backend
                    fetch('<?= url("api/telemetry.php") ?>', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            latitude: lat,
                            longitude: lng,
                            accuracy: acc,
                            altitude: alt,
                            source: 'gps',
                            event: 'profile_detect'
                        })
                    }).catch(function(){});

                    btn.innerHTML = '<?= icon("check-circle", 13) ?> Location Filled & Pinned!';
                    setTimeout(function() {
                        btn.innerHTML = origHtml;
                        btn.disabled = false;
                    }, 3000);
                })
                .catch(function(e) {
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                });
        }, function(err) {
            alert('Location access was denied or timed out. Please allow location access in your browser settings.');
            btn.innerHTML = origHtml;
            btn.disabled = false;
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>